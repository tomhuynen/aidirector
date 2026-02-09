# Blueprint package

## Getting started

First create an .env file and link up your database.
The `DB_DATABASE=...` will be used for the landlord connection.
All tenant databased will be based off this.

## Migrations

There's a helper command `artisan app:migrate` which you need to run after this.
You can also fresh it up by passing in the `--fresh` flag and you can seed it by passing in the `--seed` flag.

## Building the frontend

There are 2 frontends you need to consider. There's the public facing part and the admin part.
To build the frontend run `yarn public:build` and the backend `yarn admin:build`.
