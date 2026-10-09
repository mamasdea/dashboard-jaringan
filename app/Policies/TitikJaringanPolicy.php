<?php

namespace App\Policies;

/**
 * `update` juga mencakup hubungan di titik ini: sambungan, terminasi, dan
 * koneksi endpoint.
 */
class TitikJaringanPolicy extends KebijakanAset {}
