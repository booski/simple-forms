# Simple forms

## Setup

```
cp config.php.example config.php
editor config.php
mkdir templates
```

## Templates

All form templates must be placed in the `templates` directory and have the
extension `.form`. Any non-matching files will be ignored. The ID of a
template in the database is the filename without the `.form` extension.

### Template syntax

The file format recognizes three basic types of data: text,
questions and alternatives.

Text is anything that is not recognized as a question or an alternative. Each
line is treated as a paragraph.

A question is a line with a question type in square brackets ([]) at the end.
Possible types are `text`, `longtext`, `radio`, `check` and `range`.

`radio` and `check` type questions need to have alternatives. Alternatives are
defined by creating a block of lines immediately following the question,
indented by two spaces.

When declaring a question, it is possible to add a comma-separated list of
extra modifiers using a colon (:) trailing the question type string inside the
square brackets. The exact effect of extra modifiers depends on the question
type, see examples.form for currently supported modifiers.

Questions can be nested, so for example it is possible to have a text answer
as an alternative to a multiple-choice question.

Indentation can also be used for logical grouping. An indented block following
a non-question line will simply be treated as a 'child' of that line.

There is also basic styling. Create a header by adding between one and three
hash signs (#) at the beginning of the line. Make the line bold by adding an
exclamation mark (!) to the beginning of the line. In-line HTML tags
are also respected.

In order for a form to be listed on the index page, it must contain at least
one heading. The first heading in the form will be used as its title on
the index page.

Examples in example.form

## Changes files

This is a bit hacky and a work in progress.

Since the questions are stored in the database along with their respective
answers, any changes to the form once data has been entered will cause
exported data to contain both old and new versions of questions and answers.
In order to get rid of this duplication, especially in cases where a minor
wording change etc has been made, it is possible to log changes to questions
in a `.changes` file.

Changes files are stored in the `templates` directory along with their
corresponding forms. The changes file for `exampleform.form`
would be `exampleform.changes`.

Changes files currently have a few important limitations:

 * Only questions are handled, no answers.
 * Questions are specified in an unqualified format.
 * Question type or other metadata is not considered, only wording.

The unqualified format means that it is not possible to disambiguate between
two identical subquestions nested under different top-level questions, etc.
This means that *any question that starts out identical to another must be
updated identically in future, or interesting export results will occur.*

This will hopefully change in future with a better changes file syntax.

Changes files are wholly optional. If no changes file exists for a form, all
versions of a question will be exported, each to a unique column
in the export.

### Changes file syntax

A changes file consists of blocks of lines separated by an empty line. The
first line in a block is the most recent version, as present in the current
version of the corresponding template file. Subsequent lines in a block are
old versions of the question, that will be merged into the current
version on export.

This is an example changes file:
```
The current version of a question
A previous version of the same question
Another version

The current version of another question
Old version of this second question
```

Changing one question to use the wording from another question is guaranteed
to produce unexpected results.

## Data export

The data export function will mostly export all data unaltered, with
some notable exceptions:

 * Any answer formatted as `[number] - [arbitrary text]`
   (e.g. "3 - sometimes") will be stripped of its text part and only exported
   as `[number]` (e.g. "3").

 * As noted above, if a form has a changes file, it will be used to merge
   columns that are regarded as old variants of a question into the column
   with the latest question format.

These transformations are wholly in the export process, so the database always
contains an unchanged record of all questions and answers as
they were submitted.
