<?php
/** English / Bahasa Melayu (includes/i18n.php): the dictionary, fragments, HTML and JSON. Pure functions, no database. */
return [
    'only en and ms are valid languages; anything else is English' => function () {
        assert_same('ms', validLanguage('ms')); assert_same('en', validLanguage('en'));
        assert_same('en', validLanguage('fr')); assert_same('en', validLanguage(null)); assert_same('en', validLanguage(['ms']));
    },
    'whole texts are translated, spaces around them are kept, unknown texts stay English' => function () {
        assert_same('Menunggu', translateText('Pending', 'ms'));
        assert_same('  Menunggu ', translateText('  Pending ', 'ms'));
        assert_same('A text nobody translated', translateText('A text nobody translated', 'ms'));
        assert_same('Pending', translateText('Pending', 'en'));
    },
    'fragments inside longer sentences are translated, but never in the middle of a word' => function () {
        assert_same('Permohonan #5 telah diluluskan oleh Ketua Jabatan anda.', translateText('Your request #5 was approved by your Head of Department.', 'ms'));
        assert_same('Pending', translateText('Pending', 'en'));
        assert_true(strpos(translateText('Cancellation', 'ms'), 'Batal') === false, 'Cancel is not replaced inside Cancellation');
    },
    'dates: Malay day and month names; counts like "2 days"' => function () {
        assert_same('Rabu, 28 Oktober 2026', translateText('Wednesday, 28 October 2026', 'ms'));
        assert_same('Isn, 02 Nov 2026', translateText('Mon, 02 Nov 2026', 'ms'));
        assert_same('menunggu 2 hari', translateText('waiting 2 days', 'ms'));
    },
    'HTML: text and some attributes are translated; values, names, scripts and textareas are not' => function () {
        $in = '<html lang="en"><body><input type="text" name="Pending" value="Pending" placeholder="Search"><button type="submit">Save</button><textarea name="x">Pending</textarea>'
            . '<script>var a = "Pending"; var b = "name";</script><style>.Pending{}</style><a href="?tab=Pending" title="Cancel">Pending</a></body></html>';
        $out = translateHtml($in, 'ms');
        assert_true(strpos($out, '<html lang="ms"') === 0, 'html lang');
        assert_true(strpos($out, 'value="Pending"') !== false && strpos($out, 'name="Pending"') !== false, 'values and names kept');
        assert_true(strpos($out, 'placeholder="Cari"') !== false && strpos($out, 'title="Batal"') !== false, 'attributes translated');
        assert_true(strpos($out, '>Simpan<') !== false && strpos($out, '>Menunggu</a>') !== false, 'text translated');
        assert_true(strpos($out, '<textarea name="x">Pending</textarea>') !== false, 'textarea content is user data');
        assert_true(strpos($out, 'var a = "Menunggu"') !== false && strpos($out, 'var b = "name"') !== false, 'quoted texts in scripts');
        assert_true(strpos($out, '.Pending{}') !== false && strpos($out, 'href="?tab=Pending"') !== false, 'css and links kept');
    },
    'JSON: string values are translated and the structure is kept; no change = same bytes' => function () {
        $out = translateJson('{"success":false,"message":"Request approved.","n":3,"list":["Pending",{"s":"Approved"}]}', 'ms');
        $j = json_decode($out, true);
        assert_same(false, $j['success']); assert_same(3, $j['n']);
        assert_same('Menunggu', $j['list'][0]); assert_same('Diluluskan', $j['list'][1]['s']);
        $same = '{"a":"zzz nothing","b":1.0}';
        assert_same($same, translateJson($same, 'ms'));
        assert_same('not json', translateJson('not json', 'ms'));
    },
    'every Malay entry is a string and no key is empty' => function () {
        foreach (include __DIR__ . '/../includes/lang/ms.php' as $en => $ms) {
            assert_true(is_string($en) && trim($en) !== '' && is_string($ms) && trim($ms) !== '', "entry: $en");
        }
    },
];
