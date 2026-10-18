<?php

// Real loopback fixture: transport must abandon this delayed response at one second.
sleep(3);
header('Content-Type: application/json');
echo '{"data":{"id":"late","event_id":"late"}}';
