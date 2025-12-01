# Configuration file for the Sphinx documentation builder.
#
# For the full list of built-in configuration values, see the documentation:
# https://www.sphinx-doc.org/en/master/usage/configuration.html

# -- Project information -----------------------------------------------------

project = 'L10nGuy'
copyright = '2025, Steffen Beyer, 213tec'
author = 'Steffen Beyer'

# The short X.Y version.
version = '0.3'
# The full version, including alpha/beta/rc tags.
release = '0.3.0'

# -- General configuration ---------------------------------------------------

extensions = [
    'sphinx.ext.intersphinx',
    'sphinx.ext.todo',
]

templates_path = ['_templates']
exclude_patterns = ['_build', 'Thumbs.db', '.DS_Store']

# Use PHP syntax highlighting in code examples by default
highlight_language = 'php'

# -- Options for HTML output -------------------------------------------------

html_theme = 'alabaster'

html_theme_options = {
    'description': 'Flow CLI localisation companion for Neos CMS',
    'github_user': 'serpent213',
    'github_repo': 'Two13Tec.L10nGuy',
}

# -- Intersphinx configuration -----------------------------------------------

intersphinx_mapping = {
    'flow': ('https://flowframework.readthedocs.io/en/stable', None),
    'neos': ('https://neos.readthedocs.io/en/stable', None),
}
