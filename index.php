<?php
// The app lives in public/. Relative redirect, so it works whichever
// host name or IP address the browser used to get here.
header('Location: public/', true, 302);
exit;
