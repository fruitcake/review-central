<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A connected account tells whose turn a pull request is by itself: the account
 * ID and nickname connecting copied into a user's listed accounts go, so the
 * profile lists only what the user typed. Not undone: they count either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connected = DB::table('personal_connections')->get(['user_id', 'account_id', 'nickname'])->groupBy('user_id');

        foreach (DB::table('users')->whereIn('id', $connected->keys())->whereNotNull('source_accounts')->get(['id', 'source_accounts']) as $user) {
            $own = $connected[$user->id]->flatMap(fn (object $row): array => [$row->account_id, $row->nickname])->filter()->map(fn (string $account): string => mb_strtolower($account))->all();
            $listed = (array) json_decode((string) $user->source_accounts, true);
            $kept = array_values(array_filter($listed, fn (mixed $account): bool => ! in_array(mb_strtolower(trim((string) $account)), $own, true)));

            if ($kept !== $listed) {
                DB::table('users')->where('id', $user->id)->update(['source_accounts' => json_encode($kept)]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
