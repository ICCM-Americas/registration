{{-- The plain-text body of a TemplatedMail: the admin-authored template with
     its variables already interpolated. Unescaped on purpose — this is a
     text/plain part, where HTML entities would show literally. --}}
{!! $bodyText !!}
