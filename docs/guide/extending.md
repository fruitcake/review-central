# Extending

## Adding a provider

A provider is a driver class, registered in `config/review.php`:

- source control: implement `App\Connections\SourceControlDriver`, and `App\SourceControl\SourceControl` for the repository calls. `SourceControl` documents the shape pull requests and comments are passed in.
- tickets: implement `App\Connections\TicketDriver`, which reads one ticket by its reference (`fetch()`) and returns it as a `Ticket`.
- AI providers: implement `App\Connections\LlmDriver`, which names the environment variables the reviewer reads the key from and the reviewers it can run, and answers the diff runner's review, a yes or no about a text, and the note on whose turn a pull request is.

A driver declares its own credential and setting fields, so a new one shows up in the Connections form without further work. To say where its key or token is made, implement `App\Connections\ExplainsSetup` too: its `App\Support\SetupGuide` (the page's link, the steps and the scopes it needs) is offered as **How to get this** next to the service.

## Adding a runner

A runner is a class that implements `App\Runners\ReviewRunner`, registered under `runners` in `config/review.php`: how a review is started, and whether a run is still alive.

## Adding a stack guide

A stack guide tells the reviewer what to look for in one stack, with the reasoning, so it can judge rather than match patterns.

**For your own installation**, no code is needed: under **Instructions**, a tenant adds a guide with a stack name of its own, and picks that stack on the repositories it applies to. The administrator can add one for every tenant the same way, under **Default instructions**.

**To ship one with Review Central**:

1. Add `resources/review/skill/references/stack-<name>.md`.
2. Add the name to `ReviewStack::STACKS` in `app/Services/ReviewStack.php`, in the order the review should read it, and teach `ReviewStack::fromComposer()` what in a `composer.json` means that stack, so a repository gets it when it is added.
3. Do the same in `resources/review/skill/detect-stack.sh`: the name in `STACKS`, and a `detect_<name>` function. It is the fallback the reviewer's own step uses.
4. Add a test next to the others in `tests/Unit/ReviewStackTest.php`.

A repository's stack is read from its `composer.json` when it is added, and can be changed on the repository.

See [Contributing](./contributing) for how to propose one.
