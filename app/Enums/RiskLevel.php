<?php

namespace App\Enums;

enum RiskLevel: string
{
    case Safe = 'safe';
    case Suspicious = 'suspicious';
    case Fraud = 'fraud';
}
