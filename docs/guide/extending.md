# Extending

## Adding a provider

A provider is a driver class, registered in `config/review.php`:

- source control: implement `App\Connections\SourceControlDriver`, and `App\SourceControl\SourceControl` for the repository calls. `SourceControl` documents the shape pull requests and comments are passed in.
- tickets: implement `App\Connections\TicketDriver`, which reads one ticket by its reference and returns it as markdown.
- AI providers: implement `App\Connections\LlmDriver`, which names the environment variables the reviewer reads the key from.

A driver declares its own credential and setting fields, so a new one shows up in the Connections form without further work.

## Adding a runner

A runner is a class that implements `App\Runners\ReviewRunner`, registered under `runners` in `config/review.php`: how a review is started, and whether a run is still alive.

## Adding a stack guide

A stack guide tells the reviewer what to look for in one stack, with the reasoning, so it can judge rather than match patterns. The guides live in `resources/review/skill/references` as `stack-<name>.md`; a repository's stack is read from its `composer.json` when it is added, and can be changed on the repository.

See [Contributing](./contributing) for how to propose one.
