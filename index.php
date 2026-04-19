<?php
// SmartCart — Root redirect
// Sends visitors from /smart/ to the real homepage
header('Location: pages/index.php', true, 302);
exit;
