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
