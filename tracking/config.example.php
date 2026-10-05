<?php
// Copie para um arquivo FORA da pasta pública e indique via ECRM_TRACKING_CONFIG.
return [
    'database' => '/var/lib/ecrm-tracking/events.sqlite',
    'secret' => 'SUBSTITUA-POR-UM-SEGREDO-ALEATORIO-DE-64-CARACTERES',
    'origins' => ['https://tst.snet.app.plataformadecrm.com.br'],
    'sites' => ['ecrm-static' => 'Site eCRM - teste estático'],
    'crm_url' => 'https://tst.snet.app.plataformadecrm.com.br/webservice.php',
    'crm_token' => '',
    'assigned_user_id' => '19x27', // Integrador Make: usuário que a credencial atual consegue consultar.
    'leadsource' => 'Web Site',
    'leadstatus' => 'Pré qualificado',
    'rating' => 'Ativo',
    'score_field' => null, // Nome de um campo numérico confirmado pelo describe da API.
    'dry_run' => true,
    'crm_sync_enabled' => false, // Fluxo antigo desativado; não ativar no painel Analytics.
    'retention_days' => 30,
    'points' => ['visit' => 1, 'click' => 2, 'form_start' => 3, 'field_filled' => 1, 'form_submit' => 20],
];
