<?php

declare(strict_types=1);

return [
    // Registration
    'registration_session_expired' => 'انتهت جلسة التسجيل. يرجى البدء من جديد.',
    'completion_token_invalid'     => 'رمز الإكمال غير صالح أو منتهي. يرجى التحقق من بريدك مرة أخرى.',
    'email_already_registered'     => 'هذا البريد الإلكتروني مسجل بالفعل.',
    'magic_link_invalid'           => 'رابط التحقق غير صالح أو منتهي.',

    // Login
    'invalid_credentials' => 'بيانات الاعتماد غير صحيحة.',
    'account_inactive'    => 'حسابك غير نشط.',
    'email_not_verified'  => 'يرجى التحقق من بريدك الإلكتروني قبل تسجيل الدخول.',

    // OTP
    'otp_invalid' => 'رمز التحقق غير صالح.',
    'otp_expired' => 'انتهت صلاحية رمز التحقق.',

    // Password
    'reset_token_invalid'      => 'رمز إعادة التعيين غير صالح أو منتهي. يرجى طلب رمز جديد.',
    'current_password_invalid' => 'كلمة المرور الحالية غير صحيحة.',

    // Tokens
    'api_token_invalid_format'   => 'صيغة رمز الـ API غير صالحة.',
    'api_token_invalid_encoding' => 'ترميز رمز الـ API غير صالح.',
    'api_token_revoked'          => 'تم إلغاء رمز الـ API.',
    'api_token_expired'          => 'انتهت صلاحية رمز الـ API.',
    'refresh_token_invalid'      => 'رمز التحديث غير صالح.',
    'refresh_token_revoked'      => 'تم إلغاء رمز التحديث. يرجى تسجيل الدخول من جديد.',
    'refresh_token_reused'       => 'تم رصد إعادة استخدام رمز التحديث. تم إلغاء جميع الجلسات لهذه العائلة.',
    'refresh_token_expired'      => 'انتهت صلاحية رمز التحديث. يرجى تسجيل الدخول من جديد.',

    // Sessions
    'session_not_found' => 'الجلسة غير موجودة.',

    // Social
    'social_provider_disabled'     => 'المصادقة عبر :provider غير مفعّلة.',
    'social_authentication_failed' => 'تعذرت المصادقة مع :provider. يرجى المحاولة مرة أخرى.',
    'social_email_unverified'      => 'البريد المرتبط بحساب :provider غير موثق.',
    'social_link_token_invalid'    => 'رابط التأكيد غير صالح أو منتهي.',
    'social_user_not_found'        => 'المستخدم غير موجود.',

    // Lockout
    'account_locked' => 'محاولات كثيرة جداً. يرجى المحاولة مرة أخرى خلال :seconds ثانية.',

    // Generic
    'unauthenticated' => 'غير مصرّح.',
];
