<?php

set_time_limit(0);

require_once 'PollBot.php';
require_once 'token.php';

$bot = new PollBot(BOT_TOKEN, 'PollBotChat');
$bot->runLongpoll();
