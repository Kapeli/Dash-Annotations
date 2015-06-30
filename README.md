# Dash Annotations Server

Follow these instructions if you want to set up your own annotation server.

## Installation

* Install [Lumen](http://lumen.laravel.com/docs/installation)
* Add a MySQL database called "annotations"
* Clone this repo over your Lumen install
* Rename the `.env.example` file to `.env` and edit it
* Run `composer install`
* Install Python and [Pygments](http://pygments.org/) (used for syntax highlighting)
  * Make sure `/bin/pygmentize` exists. If it doesn't, add a link between `/bin/pygmentize` to wherever you installed Pygments
* Run `php artisan migrate` and type `Y` to confirm you want to do it in production
* Open `http://{your_server}/users/logout` in your browser and check if you get a JSON response that says you're not logged in
* Let Dash know about your server by running this command in Terminal:

```bash
# Repeat on every Mac that will connect to your server:
defaults write com.kapeli.dashdoc AnnotationsCustomServer "http(s)://{your_server}"
```

### Dokku
> https://github.com/dokku-alt/dokku-alt

* Checkout dokku branch `git checkout dokku`
* Create remote for dokku `git remote add REMOTE_NAME dokku@REMOTE_DNS_NAME:APP_NAME` (e.g. `git remote add dokku dokku@mydokku.com:dash`).
* Create the app: `ssh -t dokku@mydokku.com create dash`
* Create the database: `ssh -t dokku@mydokku.com mariadb:create dash-db`
* Link database: `ssh -t dokku@mydokku.com mariadb:link dash dash-db`
* Get the database credentials: `ssh -t dokku@mydokku.com mariadb:info dash dash-db`
	> *Results*


	```
	echo "       Host: mariadb"
	echo "       User: dash
	echo "       Password: eDFjklLgKroVme4d"
	echo "       Database: dash-db"
	echo
	echo "       MARIADB_URL=mysql2://dash:eDFjklLgKroVme4d@mariadb:3306/dash-db"
	```
* Create environmental variables:
	```
	ssh -t dokku@mydokku.com config:set dash \
	APP_ENV=production \
	APP_FALLBACK_LOCAL=en \
	APP_KEY=Uoth7eengeeH6eize0eic3Iegoo8aap0 \
	APP_LOCALE=en \
	CACHE_DRIVER=file \
	DB_CONNECTION=mysql \
	DB_DATABASE=dash-db \
	DB_HOST=mariadb \
	DB_PASSWORD=eDFjklLgKroVme4d \
	DB_USERNAME=dash \
	QUEUE_DRIVER=file \
	SESSION_DRIVER=file
	```
	
* Push to dokku: `git push dokku dokku:master`

* Get your server's URL: `ssh -t dokku@mydokku.com  url dash`
> *Results*: `http://dash.mydokku.com`


* Open http://dash.mydokku.com/users/logout in your browser and check if you get a JSON response that says you're not logged in

```bash
# Repeat on every Mac that will connect to your server:
defaults write com.kapeli.dashdoc AnnotationsCustomServer "http(s)://{your_server}"
```

* If you encounter any issues, [let me know](https://github.com/Kapeli/Dash-Annotations/issues/new)!
