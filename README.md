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

* If you encounter any issues, [let me know](https://github.com/Kapeli/Dash-Annotations/issues/new)!
