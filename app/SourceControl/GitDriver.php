<?php

declare(strict_types=1);

namespace App\SourceControl;

use App\Connections\ExplainsSetup;
use App\Connections\Fields\Secret;
use App\Connections\SourceControlDriver;
use App\Exceptions\ConnectionFailed;
use App\Models\Connection;
use App\Models\Repository;
use App\Rules\PublicUrl;
use App\Support\SetupGuide;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\Request;

/**
 * Any git server, by the address its repositories are cloned from: GitLab, Gitea,
 * a server of one's own. It has no pull requests here and nothing is posted to
 * it, so what can be done with its repositories is what needs only a clone: a
 * review of a branch as it stands.
 */
class GitDriver implements ExplainsSetup, SourceControlDriver
{
    public function label(): string
    {
        return __('Git over HTTPS (clone only)');
    }

    public function credentialFields(): array
    {
        return [
            Secret::make('password')->label(__('Password or token'))
                ->required(false)
                ->helperText(__('What the server takes to clone over HTTPS. Leave it empty for repositories anyone can clone.')),
        ];
    }

    public function settingFields(): array
    {
        return [
            TextInput::make('base_url')->label(__('Address'))->url()->required()->rule(new PublicUrl)->placeholder('https://git.example.com/acme')
                ->helperText(__('What the repositories are cloned from, without the repository: <address>/<repository>.git.')),
            TextInput::make('username')->label(__('User name'))->maxLength(120)
                ->helperText(__('With a password or token, when the server wants a name with it.')),
        ];
    }

    /**
     * Any server, so the guide names the common ones. Only a clone is made, so
     * the token never needs more than to read the code.
     */
    public function setupGuide(): SetupGuide
    {
        return new SetupGuide(
            title: __('A token to clone over HTTPS'),
            url: 'https://gitlab.com/-/user_settings/personal_access_tokens',
            linkLabel: __('Personal access tokens on GitLab.com, for example'),
            steps: [
                __('Make a token on the git server that may read the repositories: on GitLab a project, group or personal access token; on Gitea or Forgejo under **Settings → Applications**; on another server, whatever it takes for `git clone` over HTTPS.'),
                __('Give it read access to the code only: nothing is pushed or posted.'),
                __('Paste it here. Under Settings, fill in the address the repositories are cloned from, and the user name the server wants with the token (GitLab takes any name, such as `oauth2`).'),
            ],
            permissions: [
                'read_repository (GitLab)' => __('clones the repository.'),
                'read:repository (Gitea, Forgejo)' => __('the same.'),
            ],
            notes: [
                __('Leave it empty for repositories anyone can clone.'),
            ],
        );
    }

    public function test(Connection $connection): void
    {
        // An address says nothing until a repository is cloned from it.
        if (! PublicUrl::allows((string) $connection->setting('base_url'))) {
            throw new ConnectionFailed('The address has to be an https address on the public internet.');
        }
    }

    public function searchRepositories(Connection $connection, string $search): array
    {
        // A git server has no list to ask: the name is typed as it is in the address.
        $name = trim($search);

        // No way up out of the address: the name goes into the one that is cloned from.
        return preg_match('/^[\w.-]+(\/[\w.-]+)*$/', $name) === 1 && ! str_contains($name, '..') ? [$name => $name] : [];
    }

    public function installWebhook(Connection $connection, string $url): void
    {
        throw new ConnectionFailed('A git server that is only cloned from has no webhook: nothing there starts a review.');
    }

    public function client(Repository $repository): GitClient
    {
        $connection = $repository->integration;

        return new GitClient((string) $connection->setting('base_url'), $repository->slug, $connection->setting('username'), $connection->credential('password'));
    }

    public function webhookHelp(): string
    {
        return __('A git server that is only cloned from has no webhook: nothing there starts a review.');
    }

    public function webhookEvent(Connection $connection, Request $request): ?WebhookEvent
    {
        return null;
    }
}
