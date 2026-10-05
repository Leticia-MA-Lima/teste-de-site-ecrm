<?php
return [
    'property_id' => '557146931',
    'credential' => '/etc/ecrm360-analytics/service-account.json',
    'cache' => '/var/cache/ecrm360-analytics/dashboard.json',
    'token_cache' => '/var/cache/ecrm360-analytics/oauth.json',
    'timezone' => 'America/Sao_Paulo',
    'stale_seconds' => 180,
    'daily_interval' => 900,
    // Confirmar os títulos exatos numa consulta real; desconhecidos ficam em Outras páginas.
    'page_titles' => [],
    'hosts' => ['crm-revisional.ecrm360.com.br' => 'Revisional', 'habilitacao.ecrm360.com.br' => 'Habilitação', 'industria.ecrm360.com.br' => 'Indústria', 'conheca.ecrm360.com.br' => 'Geral'],
];
