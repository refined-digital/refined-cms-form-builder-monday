# Form Builder — monday.com

A monday.com integration for [RefinedCMS Form Builder](https://gitlab.com/refineddigital/cms-form-builder). On submit, the form's mapped field values create an **item** on a monday.com board via the GraphQL API (`create_item`). Mapping is done per field using the field's **Merge Field** value.

Requires `refineddigital/cms-form-builder`. No SDK — it uses Laravel's HTTP client, plus [`brick/phonenumber`](https://github.com/brick/phonenumber) (installed automatically) to format phone numbers.

## Install

```bash
composer require refineddigital/cms-form-builder-monday
php artisan refinedCMS:install-form-builder-monday
```

The install command publishes the config (`config/monday.php`) and appends `MONDAY_TOKEN=` and `MONDAY_ERROR_EMAIL=` to your `.env` (only if missing).

## monday.com setup

1. In monday.com click your avatar → **Developers** → **My access tokens** and copy the token.
2. Put it in `MONDAY_TOKEN` in your `.env`.

The token acts as that user, so they need write access to the boards you connect.

## Connecting a form

1. Edit the form and open the **Integrations** tab, toggle **monday.com** on.
2. Click **Configure** → **Config** and enter the **Board ID** (the number in the board URL: `…/boards/1234567890`). Optionally enter a **Group ID** to put items in a specific group — leave it blank for the board's top group.
3. Set each field's **Merge Field** to the board **column id**. Use `name` for the item name — without it the item is named after the form. Map several fields to `name` (e.g. First Name and Last Name) and they're joined in form order.
4. Back in **Configure → Fields**, toggle off anything you don't want sent.

## Finding a column id

On the board, enable **Developer mode** (avatar → monday.labs), then open a column's menu → **Copy column ID**. Group ids are copied the same way from the group's menu (e.g. `topics`, `new_group12345`).

## Behaviour

- **Column types.** The board's column types are looked up once and cached for a day (per board; the cache is cleared whenever a submission fails, so a changed board recovers on the next one; **Configure → Config → Clear cache** clears it by hand) so values are shaped correctly: email, phone, long text, status (by label), dropdown (by labels), checkbox, link and date. Anything else is sent as a plain string.
- **Phone numbers are sent in international format.** Numbers are parsed as local to `MONDAY_PHONE_COUNTRY` (ISO-2, default `NZ`) and sent as E.164 — with `AU`, `0412 345 678` becomes `+61412345678`. Numbers already starting with `+` keep their own country; anything unparseable is sent as typed.
- **Missing labels are created.** Status/dropdown values that don't exist on the board are added (`create_labels_if_missing`).
- **Checkbox / multi-select** fields map to dropdown labels on a dropdown column, otherwise they're joined with `, `.
- **Failures never block a submission.** Errors (including monday's HTTP-200 GraphQL errors) are `report()`ed and, if `MONDAY_ERROR_EMAIL` is set, emailed with monday's own reason. The submission and its notifications carry on.
- **No token or board, no call.**

## Config

`config/monday.php`:

```php
return [
    'token' => env('MONDAY_TOKEN'),
    'error_email' => env('MONDAY_ERROR_EMAIL'),
    'phone_country' => env('MONDAY_PHONE_COUNTRY', 'NZ'),
];
```
