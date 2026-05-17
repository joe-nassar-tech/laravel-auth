<?php

declare(strict_types=1);

return [
    // Registration
    'register_initiated'  => 'تم إرسال رمز التحقق. يرجى التحقق من بريدك الإلكتروني.',
    'register_verified'   => 'تم التحقق من البريد الإلكتروني. يرجى تعيين كلمة المرور.',
    'register_complete'   => 'اكتمل التسجيل.',
    'verification_resent' => 'تمت إعادة إرسال بريد التحقق.',

    // Authentication
    'login_success'      => 'تم تسجيل الدخول بنجاح.',
    'me_retrieved'       => 'تم جلب الملف الشخصي.',
    'logout_success'     => 'تم تسجيل الخروج بنجاح.',
    'logout_all_success' => 'تم تسجيل الخروج من جميع الأجهزة.',

    // Password
    'password_reset_sent'    => 'إذا كان البريد الإلكتروني موجوداً، فقد تم إرسال رابط إعادة التعيين.',
    'password_reset_otp_ok'  => 'تم إعادة تعيين كلمة المرور بنجاح.',
    'password_reset_link_ok' => 'تم التحقق من رابط إعادة التعيين.',
    'password_reset_success' => 'تم إعادة تعيين كلمة المرور بنجاح.',
    'password_changed'       => 'تم تغيير كلمة المرور بنجاح.',

    // Sessions
    'sessions_retrieved' => 'تم جلب الجلسات.',
    'session_terminated' => 'تم إنهاء الجلسة.',

    // API tokens
    'api_tokens_retrieved' => 'تم جلب رموز الـ API.',
    'api_token_created'    => 'تم إنشاء رمز الـ API.',
    'api_token_updated'    => 'تم تحديث رمز الـ API.',
    'api_token_revoked'    => 'تم إلغاء رمز الـ API.',

    // v2.4 — دورة حياة الحساب
    'account_deleted'        => 'تم جدولة حذف الحساب.',
    'account_restored'       => 'تم استعادة الحساب.',
    'account_status_updated' => 'تم تحديث حالة الحساب.',
    'account_deactivated'    => 'تم تعطيل الحساب. سجّل الدخول في أي وقت لإعادة تفعيله.',
    'account_reactivated'    => 'مرحباً بعودتك — تم إعادة تفعيل حسابك.',
];
