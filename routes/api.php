<?php

declare(strict_types=1);

// Served under /api. Every route here sits behind VerifyShopifySessionToken,
// which bootstrap/app.php adds to the whole api group — so a new route can't
// be left unprotected by forgetting it.
