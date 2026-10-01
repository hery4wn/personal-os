<?php
use Illuminate\Support\Facades\Artisan;
Artisan::command('personal-os:about', function () { $this->comment('Personal OS — a quiet place to move your life forward.'); });
