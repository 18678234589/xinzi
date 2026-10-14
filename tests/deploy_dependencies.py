import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / 'tools'))
from deploy_dependencies import literal_php_requires


class DependencyTests(unittest.TestCase):
    def test_header_missing_dependency(self):
        self.assertEqual(literal_php_requires('includes/header.php', "<?php require_once __DIR__ . '/ProjectTerms.php';"), ['includes/ProjectTerms.php'])

    def test_parent_and_parentheses(self):
        self.assertEqual(literal_php_requires('project/vault.php', "<?php require(__DIR__.'/../includes/ProjectVault.php');"), ['includes/ProjectVault.php'])

    def test_root_entry(self):
        self.assertEqual(literal_php_requires('index.php', "<?php require_once __DIR__.'/includes/auth.php';"), ['includes/auth.php'])

    def test_no_comments_or_strings(self):
        source = '''<?php // require __DIR__.'/bad.php';
        /* require_once __DIR__.'/bad2.php'; */
        $x = "require __DIR__.'/bad3.php';";
        require_once __DIR__.'/real.php';'''
        self.assertEqual(literal_php_requires('includes/header.php', source), ['includes/real.php'])

    def test_dynamic_and_duplicates(self):
        self.assertEqual(literal_php_requires('includes/header.php', '''<?php
            require __DIR__."/$name.php";
            require_once __DIR__.'/one.php';require __DIR__.'/one.php';'''), ['includes/one.php'])

    def test_traversal_rejected(self):
        with self.assertRaises(ValueError):
            literal_php_requires('project/page.php', "<?php require __DIR__.'/../../outside.php';")


if __name__ == '__main__':
    unittest.main()
