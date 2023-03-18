# Description

This project is an implementation of the restic's [REST backend API](https://restic.readthedocs.io/en/latest/100_references.html#rest-backend) in PHP. It is a replacement for [restic/rest-server](https://github.com/restic/rest-server).

|Feature        |restic/rest-server|restic-rest-php|
|---------------|-----------|---------------|
| Auth          | Yes (.htpasswd) | Yes (web server) |
| TLS           | Yes       | Yes (web server) |
| Append only   | Yes       | Yes            |
| Private repos | Yes       | Yes            |
| Metrics       | Yes       | No             |
| Quota         | Yes       | No             |


# Usage

Change `DataDir` in the config file to the absolute path of where you want the repositories to live. 
It might be a good idea to put `DataDir` outside of the web root but it's not obligatory.

NGINX:
````
location / {
    rewrite ^(.*)$ /index.php?$1 last;
}
````

Apache:
````
RewriteEngine On
RewriteRule ^.*$ index.php [L]
````

Testing (set NO_AUTH to true):
````
$ php -S 127.0.0.1:8000 index.php
$ restic -r rest:http://127.0.0.1:8000/ init
````
