# excimer-ui-server

## Getting started

1. **Download.**
   Require [wikimedia/excimer-ui-server](https://packagist.org/packages/wikimedia/excimer-ui-server) from Packagist.org, or run
   `composer install --prefer-stable --no-dev` in this directory to
   fetch the dependencies.

2. **Create database schema.**
   We recommended creating a dedicated mysql user and database.
   Refer to [tables.sql](tables.sql) for the schema.

3. **Expose `public_html/` from a web server.**
   Either as the document root for an entire domain,
   or from a subdirectory.

## Configuration options

### Prety URLs

When using choosing a subdirectory, we recommend using an `Alias`
in your Apache httpd configuration. This produces URLs like
https://perf.example/excimer/speedscope/

```
<VirtualHost *:80>
Alias /excimer /var/www/excimer-ui-server/public_html/index.php
</VirtualHost>
```

Alternatively, if you use the document root, or if you don't have
root access to Apache httpd config (i.e. only `.htaccess`), then
you can use a RewriteRule:

```
RewriteEngine On
RewriteRule ^/excimer/(.*)$ /excimer/index.php
```

### Load config from `/etc/`

If you install Excimer UI from Git, you can place the configuration
file at `config/config.json`, which is automatically discovered.

To read it from a custom location, set the `EXCIMER_CONFIG_PATH`
environment variable. Example for Apache:

```
SetEnv EXCIMER_CONFIG_PATH=/etc/excimer-ui-server/config.json
```

## Local development

This requires PHP 7.4+ and a MariaDB (or MySQL) server on localhost.

* Create database and user: `mysql < install_dev.sql`
* Create table from schema: `mysql -D excimer < tables.sql`
* Run `composer serve`

You can now access <http://localhost:4000/index.php/speedscope/>.
