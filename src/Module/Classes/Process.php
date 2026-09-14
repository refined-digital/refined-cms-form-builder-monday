<?php

namespace RefinedDigital\Monday\Module\Classes;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RefinedDigital\CMS\Modules\Core\Mail\Notification;
use RefinedDigital\FormBuilder\Module\Contracts\FormBuilderIntegrationInterface;

class Process implements FormBuilderIntegrationInterface
{
    const API = 'https://api.monday.com/v2';

    /**
     * Create a monday.com item on the form's board from the submission.
     *
     * Each field's Merge Field is the board column id; the special id `name` is the
     * item name (falls back to the form name). Fields with no merge field, or
     * toggled off in the Configure modal, are ignored.
     *
     * A monday failure is reported but never aborts the submission, so we always
     * return null (success) regardless.
     */
    public function process($request, $form, $settings)
    {
        $token = config('monday.token');
        $config = $settings['config'] ?? [];
        $boardId = trim((string) ($config['board_id'] ?? ''));

        if (! $token || $boardId === '') {
            return null;
        }

        $values = $this->values($request, $form, $config);
        if (! $values) {
            return null;
        }

        try {
            $this->send($token, $boardId, trim((string) ($config['group_id'] ?? '')), $values, $form);
        } catch (\Throwable $e) {
            // swallow so a monday outage never blocks the submission or the email
            report($e);
            // the board may have changed since the types were cached; refetch next time
            Cache::forget($this->typesKey($boardId));
            $this->notifyFailure($e, $form);
        }

        return null;
    }

    /**
     * Map the submission to column id => value via the fields' merge fields,
     * honouring the per-field toggles and dropping anything left blank.
     *
     * A form that has never been configured sends every field with a merge field.
     */
    protected function values($request, $form, array $config): array
    {
        $saved = $config['fields'] ?? null;
        $enabled = collect($saved)->where('enabled', true)->pluck('key')->all();

        $values = [];
        foreach ($form->fields as $field) {
            if (! $field->merge_field) {
                continue;
            }

            if ($saved && ! in_array($field->field_name, $enabled, true)) {
                continue;
            }

            $value = $this->clean($request->get($field->field_name));
            if ($value !== '' && $value !== []) {
                $values[$field->merge_field] = $value;
            }
        }

        return $values;
    }

    protected function send(string $token, string $boardId, string $groupId, array $values, $form): void
    {
        // column types decide the value shape (status/email/long text etc. reject plain
        // strings); cached per board so it's one lookup a day, not one per submission
        $types = Cache::remember($this->typesKey($boardId), now()->addDay(), function () use ($token, $boardId) {
            $data = $this->query($token, 'query ($ids: [ID!]) { boards(ids: $ids) { columns { id type } } }', [
                'ids' => [$boardId],
            ]);

            return collect($data['boards'][0]['columns'] ?? [])->pluck('type', 'id')->all();
        });

        $name = $values['name'] ?? $form->name ?? 'Form submission';
        unset($values['name']);

        $columnValues = [];
        foreach ($values as $id => $value) {
            // an unknown column id is left for monday to reject, which lands in the alert
            $columnValues[$id] = $this->format($types[$id] ?? 'text', $value);
        }

        $this->query(
            $token,
            // a null group_id falls back to the board's top group
            'mutation ($board: ID!, $group: String, $name: String!, $values: JSON) {
                create_item(board_id: $board, group_id: $group, item_name: $name, column_values: $values, create_labels_if_missing: true) { id }
            }',
            [
                'board'  => $boardId,
                'group'  => $groupId ?: null,
                'name'   => is_array($name) ? implode(', ', $name) : $name,
                'values' => json_encode((object) $columnValues),
            ]
        );
    }

    /**
     * Config tab "Clear cache" button: forget the board's cached column types so
     * column changes on monday are picked up by the next submission.
     */
    public function clearCache($form, array $config): string
    {
        $boardId = trim((string) ($config['board_id'] ?? ''));
        if ($boardId === '') {
            return 'Enter a Board ID first';
        }

        Cache::forget($this->typesKey($boardId));

        return 'Column cache cleared';
    }

    protected function typesKey(string $boardId): string
    {
        return 'monday.column-types.'.$boardId;
    }

    /**
     * Shape a value for a monday column type.
     */
    public function format(string $type, string|array $value): string|array
    {
        $text = is_array($value) ? implode(', ', $value) : $value;

        return match ($type) {
            'email'     => ['email' => $text, 'text' => $text],
            'phone'     => ['phone' => preg_replace('/[^\d+]/', '', $text), 'countryShortName' => config('monday.phone_country', 'NZ')],
            'long_text' => ['text' => $text],
            'status'    => ['label' => $text],
            'dropdown'  => ['labels' => (array) $value],
            'checkbox'  => ['checked' => 'true'],
            'link'      => ['url' => $text, 'text' => $text],
            'date'      => ['date' => $text],
            default     => $text,
        };
    }

    /**
     * Run a GraphQL call. monday answers most errors with HTTP 200, so the body is
     * checked as well as the status.
     */
    protected function query(string $token, string $query, array $variables): array
    {
        $response = Http::withHeaders(['Authorization' => $token])
            ->acceptJson()
            ->post(self::API, ['query' => $query, 'variables' => $variables])
            ->throw();

        $reason = $this->errors($response->json());
        if ($reason !== '') {
            throw new \RuntimeException($reason);
        }

        return $response->json('data') ?? [];
    }

    /**
     * monday's error text from either of its error shapes.
     */
    protected function errors($json): string
    {
        if (! is_array($json)) {
            return '';
        }

        return collect($json['errors'] ?? [])
            ->pluck('message')
            ->push($json['error_message'] ?? null)
            ->filter()
            ->implode("\n");
    }

    /**
     * Email whoever is set in monday.error_email why the submission didn't reach
     * monday — the API's own reason, not a stack trace.
     */
    protected function notifyFailure(\Throwable $e, $form): void
    {
        $to = config('monday.error_email');
        if (! $to) {
            return;
        }

        $name = $form->name ?? 'Unknown form';

        $settings = (object) [
            'subject'      => 'monday.com submission failed: '.$name,
            'body'         => $this->body($name, $this->reason($e)),
            'email_accent' => config('form-builder.email.accent_colour', '#1f2937'),
            'email_logo'   => config('form-builder.email.logo_url'),
        ];

        try {
            // sent directly rather than through EmailRepository — an alert about a
            // failure shouldn't need the database to land
            Mail::to($to)->send(new Notification($settings));
        } catch (\Throwable $mailError) {
            report($mailError);
        }
    }

    /**
     * Inlined HTML for the notification template's body slot.
     */
    protected function body(string $name, string $reason): string
    {
        return '<p style="margin:0 0 16px 0;">A form submission could not be sent to monday.com. '
            .'The submission itself was not affected.</p>'
            .'<p style="margin:0 0 4px 0;"><strong>Form:</strong> '.e($name).'</p>'
            .'<p style="margin:16px 0 8px 0;"><strong>Reason</strong></p>'
            .'<div style="padding:12px 16px; background-color:#f9fafb; border-left:3px solid #e5e7eb; '
            .'font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:13px; '
            .'line-height:1.5; color:#374151; white-space:pre-wrap; word-break:break-word;">'
            .e($reason)
            .'</div>';
    }

    protected function reason(\Throwable $e): string
    {
        if ($e instanceof RequestException && $e->response) {
            $reason = $this->errors($e->response->json()) ?: Str::limit($e->response->body(), 500);

            return 'HTTP '.$e->response->status().': '.$reason;
        }

        return $e->getMessage();
    }

    protected function clean($value): string|array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $value), 'strlen'));
        }

        return trim((string) $value);
    }
}
