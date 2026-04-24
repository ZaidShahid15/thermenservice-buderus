<!DOCTYPE html>
<html @yield('html_attributes')>
<head>
@yield('head')
</head>
<body @yield('body_attributes')>
@yield('body_start')
@include('partials.header')
@yield('content')
@include('partials.footer')
@yield('body_end')
</body>
</html>