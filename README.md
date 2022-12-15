# excimer-ui-server

## Local development

This requires PHP 7.4+ and a MariaDB (or MySQL) server on localhost.

* Create database and user: `mysql < install_dev.sql`
* Create table from schema: `mysql -D excimer < tables.sql`
* Run `composer serve`

You can now access <http://localhost:4000/index.php/speedscope/>.
