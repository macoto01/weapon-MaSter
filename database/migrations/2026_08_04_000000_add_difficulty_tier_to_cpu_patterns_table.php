<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cpu_patterns', function (Blueprint $table) {
            // CPUの強さを5段階(1=最弱〜5=最強)で表す。ラウンド進行に応じて
            // 対戦相手をこの段階から選出する(MatchStateService::currentCpuTier参照)。
            $table->unsignedTinyInteger('difficulty_tier')->default(1)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('cpu_patterns', function (Blueprint $table) {
            $table->dropColumn('difficulty_tier');
        });
    }
};
