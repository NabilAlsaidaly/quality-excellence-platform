<?php

namespace App\Actions\OrganizationalUnits;

use DomainException;

final class InvalidHierarchy extends DomainException
{
    public function __construct()
    {
        parent::__construct('The organizational hierarchy is invalid.');
    }
}
