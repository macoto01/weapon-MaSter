<?php

namespace App\Domain\Combat;

/**
 * 検証用ゲームデザイン仕様書 1章の定義(SPD基準値10=1回/秒相当)に基づく
 * イベント駆動の自動戦闘シミュレーション。企画書2章「自動戦闘フェーズ」に対応。
 *
 * 攻撃間隔(秒) = 10 / SPD。ボウ(no_attack_window)は最初のX秒は攻撃イベントが発生しない。
 * 双撃の刃(double_hit)は1回の攻撃イベントで2回分のダメージを与える。
 *
 * ST(スタミナ)はサイド単位の共用プールで、攻撃のたびに武器のST消費量分だけ減り、
 * 時間経過で一定量ずつ回復する(バトル準備画面ワイヤーフレーム仕様7章)。
 * プール残量が武器のST消費量に満たない場合、その武器は攻撃できず、
 * 回復によって消費量を賄えるようになる時刻まで次の攻撃イベントを先送りする。
 */
class BattleSimulator
{
    private const MAX_TIME_SECONDS = 60.0;

    // ST不足による待機の再試行が病的なケースで無限ループ化しないための安全弁(ログ件数とは別カウント)。
    private const MAX_EVENTS = 5000;

    /**
     * @param  array{name:string, hp:int, st:float, attackers: array<int,array<string,mixed>>}  $player
     * @param  array{name:string, hp:int, st:float, attackers: array<int,array<string,mixed>>}  $enemy
     */
    public function simulate(array $player, array $enemy, float $stRegenPerSecond = 0.0): array
    {
        $sides = [
            'player' => $this->prepareSide($player),
            'enemy' => $this->prepareSide($enemy),
        ];

        $log = [];
        $time = 0.0;
        $events = 0;

        while ($time < self::MAX_TIME_SECONDS) {
            if (++$events > self::MAX_EVENTS) {
                break; // safety valve against pathological ST待機の再試行
            }

            $next = $this->nextEvent($sides);
            if ($next === null || $next['time'] > self::MAX_TIME_SECONDS) {
                break;
            }

            $time = $next['time'];
            $attackerSideKey = $next['side'];
            $defenderSideKey = $attackerSideKey === 'player' ? 'enemy' : 'player';
            $attackerSide = &$sides[$attackerSideKey];
            $attacker = &$attackerSide['attackers'][$next['index']];

            $this->syncStamina($attackerSide, $time, $stRegenPerSecond);

            $cost = $attacker['stamina_cost'] ?? 0;
            if ($cost > $attackerSide['st']) {
                // ST不足で攻撃できない(仕様7章)。回復によって賄えるようになる時刻まで先送りする。
                $deficit = $cost - $attackerSide['st'];
                $attacker['next_attack_time'] = $stRegenPerSecond > 0
                    ? $time + $deficit / $stRegenPerSecond
                    : self::MAX_TIME_SECONDS + 1; // 回復しない場合はこの武器は以後攻撃不能
                unset($attacker, $attackerSide);

                continue;
            }

            $attackerSide['st'] -= $cost;

            $hits = $attacker['double_hit'] ? 2 : 1;
            $damage = $attacker['effective_atk'] * $hits;
            $sides[$defenderSideKey]['hp'] = max(0, $sides[$defenderSideKey]['hp'] - $damage);
            $attackerSide['damage_dealt'][$attacker['placed_item_id']] =
                ($attackerSide['damage_dealt'][$attacker['placed_item_id']] ?? 0) + $damage;

            $log[] = [
                'time' => round($time, 2),
                'side' => $attackerSideKey,
                'attacker' => $attacker['name'],
                'hits' => $hits,
                'damage' => round($damage, 2),
                'defender_hp_after' => round($sides[$defenderSideKey]['hp'], 2),
                'attacker_st_after' => round($attackerSide['st'], 2),
                'attacker_st_max' => $attackerSide['max_st'],
            ];

            $attacker['next_attack_time'] += $attacker['interval'];
            unset($attacker, $attackerSide);

            if ($sides[$defenderSideKey]['hp'] <= 0) {
                break;
            }

            if (count($log) > 500) {
                break; // safety valve against pathological configurations
            }
        }

        return $this->buildResult($sides, $log, $time);
    }

    private function prepareSide(array $side): array
    {
        $attackers = [];
        foreach ($side['attackers'] as $attacker) {
            $interval = 10 / $attacker['effective_spd'];
            $window = $attacker['no_attack_window'] ?? 0.0;
            $attackers[] = $attacker + [
                'interval' => $interval,
                'next_attack_time' => max($interval, $window > 0 ? $window : 0.0),
            ];
        }

        $maxSt = (float) ($side['st'] ?? 0.0);

        return [
            'name' => $side['name'],
            'hp' => $side['hp'],
            'max_hp' => $side['hp'],
            'st' => $maxSt,
            'max_st' => $maxSt,
            'st_last_update' => 0.0,
            'attackers' => $attackers,
            'damage_dealt' => [],
        ];
    }

    private function syncStamina(array &$side, float $time, float $stRegenPerSecond): void
    {
        $elapsed = $time - $side['st_last_update'];
        if ($elapsed > 0 && $stRegenPerSecond > 0) {
            $side['st'] = min($side['max_st'], $side['st'] + $elapsed * $stRegenPerSecond);
        }
        $side['st_last_update'] = $time;
    }

    private function nextEvent(array &$sides): ?array
    {
        $best = null;
        foreach (['player', 'enemy'] as $sideKey) {
            if ($sides[$sideKey]['hp'] <= 0) {
                continue;
            }
            foreach ($sides[$sideKey]['attackers'] as $index => $attacker) {
                // player→enemy の順で走査するため、同時刻は player 側が先に処理される(決定的なタイブレーク)
                if ($best === null || $attacker['next_attack_time'] < $best['time']) {
                    $best = ['time' => $attacker['next_attack_time'], 'side' => $sideKey, 'index' => $index];
                }
            }
        }

        return $best;
    }

    private function buildResult(array $sides, array $log, float $endTime): array
    {
        $playerHp = $sides['player']['hp'];
        $enemyHp = $sides['enemy']['hp'];

        if ($playerHp <= 0 && $enemyHp <= 0) {
            $winner = 'draw';
        } elseif ($playerHp <= 0) {
            $winner = 'enemy';
        } elseif ($enemyHp <= 0) {
            $winner = 'player';
        } else {
            $playerRatio = $sides['player']['max_hp'] > 0 ? $playerHp / $sides['player']['max_hp'] : 0;
            $enemyRatio = $sides['enemy']['max_hp'] > 0 ? $enemyHp / $sides['enemy']['max_hp'] : 0;
            $winner = match (true) {
                $playerRatio > $enemyRatio => 'player',
                $enemyRatio > $playerRatio => 'enemy',
                default => 'draw',
            };
        }

        return [
            'winner' => $winner,
            'duration' => round($endTime, 2),
            'player' => [
                'hp_remaining' => round($playerHp, 2),
                'max_hp' => $sides['player']['max_hp'],
                'st_remaining' => round($sides['player']['st'], 2),
                'max_st' => $sides['player']['max_st'],
                'damage_dealt' => $sides['player']['damage_dealt'],
            ],
            'enemy' => [
                'hp_remaining' => round($enemyHp, 2),
                'max_hp' => $sides['enemy']['max_hp'],
                'st_remaining' => round($sides['enemy']['st'], 2),
                'max_st' => $sides['enemy']['max_st'],
                'damage_dealt' => $sides['enemy']['damage_dealt'],
            ],
            'log' => $log,
        ];
    }
}
