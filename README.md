
# chamber-petitions

[![MIT license](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)

Very simple draft of an application that scrapes petition data from the [website](http://chamber.lu/wps/portal/public/Accueil/TravailALaChambre/Petitions/RoleDesPetitions) of the *Chambre des Députés du Grand-Duché de Luxembourg*. It is the data source of my bachelor thesis *The Petition Interaction* and is inspired by [petitions.lu](https://petitions.lu) built by [Tezza](https://twitter.com/FAQ).

## Install

Make sure you have [Docker](https://www.docker.com/) installed locally.

Run `docker-compose up`.

Add following entry to your local `/etc/hosts` file:

```
127.0.0.1 petitions.dev
```

Install dependencies.

```
docker exec -i petitions-web /bin/bash -c "composer install"
```

## Endpoints

### GET /

Fetches all petitions from the website listing and detail pages.

Saves data to:

- [data/petitions.json](https://lab.kniwweler.com/chamber-petitions/data/petitions.json)
- [data/petitions.csv](https://lab.kniwweler.com/chamber-petitions/data/petitions.csv)

### GET /?id=50

Fetches a single petition and its listing of signatures.

Saves data to:

- [data/petition-[type]-[number].json](https://lab.kniwweler.com/chamber-petitions/data/petition-public-343.json)
