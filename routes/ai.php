<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\LaravelifyServer;
use Laravel\Mcp\Facades\Mcp;

// The MCP server for AI clients (Claude Code, Cursor, …). A token from Settings → MCP signs in.
Mcp::web('/mcp', LaravelifyServer::class)
    ->middleware([AuthenticateMcpToken::class, 'throttle:120,1'])
    ->name('mcp');
