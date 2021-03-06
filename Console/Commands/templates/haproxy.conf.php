defaults
    mode http
    retries 3
    option redispatch
    maxconn 5000
    option forwardfor
    timeout connect 5000
    timeout client 300000
    timeout server 300000

frontend react
    bind 0.0.0.0:8082
    mode http
    option http-server-close
    default_backend react

backend react
    balance roundrobin
    mode http
    stats enable
    stats uri /stats
    option httpchk HEAD /check
<?php for ($i = 1; $i <= $config['workers']; ++$i): ?>
    server app<?php echo sprintf('%02d', $i) ?> localhost:<?php echo $config['port'] + 1 + $i ?> maxconn 1 check
<?php endfor ?>
