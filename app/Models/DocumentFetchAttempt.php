<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentFetchAttempt extends Model
{
    protected $fillable = [
        'request_id',
        'vin',
        'source_system',
        'status',
        'http_status',
        'duration_ms',
        'document_count',
        'error_message',
    ];
}
