<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex">

    <title>Oups ! — NIJAC</title>

    <style>
        <?= preg_replace('#[\r\n\t ]+#', ' ', file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'debug.css')) ?>
    </style>
</head>
<body>

    <div class="container text-center">

        <h1 class="headline">Oups !</h1>

        <p class="lead">Une erreur technique est survenue. Merci de réessayer dans quelques instants.<br>Si le problème persiste, contactez l'administrateur NIJAC.</p>

    </div>

</body>

</html>
