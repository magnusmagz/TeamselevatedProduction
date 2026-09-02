web: vendor/bin/heroku-php-apache2
worker: php workers/queue-worker.php
worker_sends: php workers/queue-worker.php --queues=email,sms --ticks=off
