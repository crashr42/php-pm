PHP ProcessManager for Request-Response Applications
====================================================

PHP-PM is a process manager for Request-Response Frameworks running in a ReactPHP environment.
The approach of this is to kill the expensive bootstrap of PHP (declaring symbols) and bootstrap of feature-rich frameworks.

More information can be found in the article: [Bring High Performance Into Your PHP App (with ReactPHP)](http://marcjschmidt.de/blog/2014/02/08/php-high-performance.html)

### Command

```bash
./bin/ppm start --help
Usage:
 start [--bridge="..."] [--port[="..."]] [--workers[="..."]] [--bootstrap[="..."]] [--app-env[="..."]] [working-directory]

Arguments:
 working-directory     The working directory.  (default: "./")

Options:
 --bridge              The bridge we use to convert a ReactPHP-Request to your target framework.
 --port                Load-Balancer port. Default is 8080
 --workers             Worker count. Default is 8. Should be minimum equal to the number of CPU cores.
 --worker-memory-limit Memory limit in MB per worker process.
 --app-env             The that your application will use to bootstrap.
 --bootstrap           The class that will be used to bootstrap your application.
 --help (-h)           Display this help message.
 --quiet (-q)          Do not output any message.
 --verbose (-v|vv|vvv) Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
 --version (-V)        Display this application version.
 --ansi                Force ANSI output.
 --no-ansi             Disable ANSI output.
 --no-interaction (-n) Do not ask any interactive question.
```

### Example

```bash
$ ./bin/ppm start ~/my/path/to/symfony/ --bridge=httpKernel
```

Port 5500 used by master process. Root url showing cluster status.

Port 5501 used by internal balancer.

Each worker starts its own HTTP Server which listens on port 5502, 5503, 5504 etc. Range is `5501 -> 5500+<workersCount>`.

### Setup 1. Use external Load-Balancer

![ReactPHP with external Load-Balancer](doc/reactphp-external-balancer.jpg)

Example config for NGiNX:

```nginx
upstream backend  {
    server 127.0.0.1:5501;
    server 127.0.0.1:5502;
    server 127.0.0.1:5503;
    server 127.0.0.1:5504;
    server 127.0.0.1:5505;
    server 127.0.0.1:5506;
}

server {
    root /path/to/symfony/web/;
    server_name servername.com;
    location / {
        try_files $uri @backend;
    }
    location @backend {
        proxy_pass http://backend;
    }
}
```

Example config for HAProxy:

```haproxy
frontend react
    bind 0.0.0.0:80
    mode http
    option http-server-close
    acl upload path_reg .*upload.*
    use_backend httpd if upload
    default_backend react

backend httpd
    mode http
    server app01 localhost:8000

backend react
    balance roundrobin
    mode http
    stats enable
    stats uri /stats
    option httpchk HEAD /check
    server app01 localhost:5502 maxconn 1 check
    server app02 localhost:5503 maxconn 1 check
    server app03 localhost:5504 maxconn 1 check
```

### Setup 2. Use internal Load-Balancer

This setup is slower as we can't load balance incoming connections as fast as NGiNX it does,
but it's perfect for testing purposes.

![ReactPHP with internal Load-Balancer](doc/reactphp-internal-balancer.jpg)

### Restarting

```bash
$ ./bin/ppm restart 5500
```

Graceful restart all child processes and reload code with zero downtime.

Restarting working only with haproxy as load balancer.

### Status

```bash
$ ./bin/ppm status 5500
```

Show cluster status.
