<?php
session_start();
session_destroy();
header('Location: /LinkVault/?toast=logged_out');
exit;