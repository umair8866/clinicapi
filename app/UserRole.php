<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case ClinicAdmin = 'clinic_admin';
    case ClinicUser = 'clinic_user';
}
