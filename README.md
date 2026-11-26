# Droids

A software factory control room: Claude Code and Codex "droids" pick up GitHub
Project issues, open PRs, review each other's work and ship to Laravel Cloud,
while you watch, chat with them and steer.

Built with Laravel 13, Inertia and React 19, shadcn/ui and AI Elements, on the
Laravel React starter kit.

## Screens

- **Hangar**: every droid with its status, current task, live step and spend, plus activity.
- **Projects**: milestone overview charts, roadmap (milestones > features > tasks), Laravel Cloud.
- **Board**: GitHub Project columns with builder/reviewer pairing, PR and checks chips.
- **Droids**: roster with engine, model, subscription in use, Reflect and Chat.
- **Subscriptions**: Claude and Codex subscriptions with usage, weekly schedule and fallback rules.

## Getting started

```bash
composer setup          # install, .env, key, migrate, npm install, build
php artisan db:seed     # test@example.com / password, plus the sample factory
composer dev            # app, queue, logs and Vite
```

`php artisan db:seed --class=FactoryDemoSeeder` reloads only the sample factory.

Sign-up is open only until the first account exists. Set `DROIDS_OPEN_REGISTRATION=true` to let more people register.

To run it on a server and connect the GitHub App, follow [docs/deploy-digitalocean.md](docs/deploy-digitalocean.md).

## Roadmap

1. Scaffold and UI shell: every screen renders from the database, seeded with sample data.
2. Core data and actions (in progress): board moves, assignments, droids, tasks, milestones, features, subscriptions and the schedule are saved.
3. GitHub App: repos, Projects v2 sync, PRs, checks and reviews through webhooks.
4. Droid runners: Claude Code and Codex on your subscriptions, following the schedule and fallback rules.
5. Planner, Reflect and "Propose what's next".
6. Laravel Cloud: deployments, health, metrics and logs from the Cloud API.
