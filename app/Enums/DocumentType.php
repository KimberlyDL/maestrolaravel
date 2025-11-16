<?php

namespace App\Enums;

enum DocumentType: string
{
    case FinanceReport  = 'finance_report';
    case EventProposal  = 'event_proposal';
    case Other          = 'other';
}