# Simple forms

## Setup

```
cp config.php.example config.php
editor config.php
mkdir templates
```

## Templates

All form templates must be placed in the `templates` directory and have the extension `.form`. Any non-matching files will be ignored. The ID of a template in the database is the filename without the `.form` extension.

### Template syntax

The file format recognizes three basic types of data: text, questions and alternatives.

Text is anything that is not recognized as a question or an alternative. Each line is treated as a paragraph.

A question is a line with a question type in square brackets ([]) at the end. Possible types are `text`, `longtext`, `radio`, `check` and `range`.

`radio` and `check` type questions need to have alternatives. Alternatives are defined by creating a block of lines immediately following the question, indented by two spaces.

When declaring a question, it is possible to add a comma-separated list of extra modifiers using a colon (:) trailing the question type string inside the square brackets. The exact effect of extra modifiers depends on the question type, see examples.form for currently supported modifiers.

Questions can be nested, so for example it is possible to have a text answer as an alternative to a multiple-choice question.

Indentation can also be used for logical grouping. An indented block following a non-question line will simply be treated as a 'child' of that line.

There is also basic styling. Create a header by adding between one and three hash signs (#) at the beginning of the line. Make the line bold by adding an exclamation mark (!) to the beginning of the line. In-line HTML tags are also respected.

In order for a form to be listed on the index page, it must contain at least one heading. The first heading in the form will be used as its title on the index page.

Examples in example.form

== Data export

The data export function will mostly export all data unaltered, with one notable exception:

Any answer formatted as "<number> - <arbitrary text>" (e.g. "3 - sometimes") will be stripped of its text part and only exported as "<number>" (e.g. "3").

The full answer text is stored in the database, so it is possible to retrieve the full answer with a bit of work if necessary.
