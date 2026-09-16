<?php

namespace App\Filament\Pages;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SupportOverview extends AdminPage
{
    protected static string $permission = 'admin.support.read';

    protected static ?string $title = 'Поддержка и аудит';

    protected static ?string $navigationLabel = 'Поддержка и аудит';

    protected string $view = 'filament.pages.support-overview';

    public string $section = 'users';

    public int $pageNumber = 1;

    public string $filterId = '';

    public function mount(): void
    {
        $this->authorizeAction();
    }

    public function selectSection(string $section): void
    {
        $this->authorizeAction();
        Validator::make(['section' => $section], ['section' => [Rule::in(['users', 'workspaces', 'subscriptions', 'usage', 'audit'])]])->validate();
        $this->section = $section;
        $this->filterId = '';
        $this->pageNumber = 1;
    }

    public function applyFilter(): void
    {
        $this->authorizeAction();
        Validator::make(['filterId' => $this->filterId], ['filterId' => 'nullable|uuid'])->validate();
        $this->pageNumber = 1;
    }

    public function nextPage(): void
    {
        $this->authorizeAction();
        $this->pageNumber = min(1000, $this->pageNumber + 1);
    }

    public function previousPage(): void
    {
        $this->authorizeAction();
        $this->pageNumber = max(1, $this->pageNumber - 1);
    }

    protected function getViewData(): array
    {
        $this->authorizeAction();
        Validator::make(['section' => $this->section, 'page' => $this->pageNumber, 'filterId' => $this->filterId], [
            'section' => [Rule::in(['users', 'workspaces', 'subscriptions', 'usage', 'audit'])],
            'page' => 'integer|min:1|max:1000', 'filterId' => 'nullable|uuid',
        ])->validate();
        // Select lists are deliberately explicit: no model serialization, secrets, GPS or payload fields.
        [$query, $filterColumn, $columns] = match ($this->section) {
            'users' => [DB::table('users')->select('id', 'name', 'status', 'created_at')->orderByDesc('created_at'), 'id',
                ['id' => 'ID пользователя', 'name' => 'Имя', 'status' => 'Статус', 'created_at' => 'Создан']],
            'workspaces' => [DB::table('workspaces')->select('id', 'name', 'type', 'status', 'created_at')->orderByDesc('created_at'), 'id',
                ['id' => 'ID пространства', 'name' => 'Название', 'type' => 'Тип', 'status' => 'Статус', 'created_at' => 'Создано']],
            'subscriptions' => [DB::table('subscriptions as s')->join('plan_prices as p', 'p.id', '=', 's.plan_price_id')->join('plans as plan', 'plan.id', '=', 'p.plan_id')
                ->select('s.id', 's.workspace_id', 's.status', 's.period_end', 'plan.name', 'plan.version', 'p.interval', 'p.amount_minor')->orderByDesc('s.created_at'), 's.workspace_id',
                ['id' => 'ID подписки', 'workspace_id' => 'Пространство', 'name' => 'Тариф', 'version' => 'Версия', 'status' => 'Статус', 'interval' => 'Период', 'amount_minor' => 'Цена, коп.', 'period_end' => 'Оплачено до']],
            'usage' => [DB::table('usage_counters as u')->join('features as f', 'f.id', '=', 'u.feature_id')->select('u.workspace_id', 'f.key', 'u.consumed', 'u.reserved', 'u.period_start', 'u.period_end')->orderByDesc('u.period_start'), 'u.workspace_id',
                ['workspace_id' => 'Пространство', 'key' => 'Возможность', 'consumed' => 'Использовано', 'reserved' => 'Зарезервировано', 'period_start' => 'Начало', 'period_end' => 'Конец']],
            'audit' => [DB::table('audit_logs')->select('id', 'actor_id', 'workspace_id', 'action', 'target_type', 'target_id', 'created_at')->orderByDesc('created_at'), 'workspace_id',
                ['id' => 'ID события', 'actor_id' => 'Инициатор', 'workspace_id' => 'Пространство', 'action' => 'Действие', 'target_type' => 'Тип объекта', 'target_id' => 'Объект', 'created_at' => 'Дата']],
        };
        if ($this->filterId !== '') {
            $query->where($filterColumn, $this->filterId);
        }
        $rows = $query->offset(($this->pageNumber - 1) * 50)->limit(51)->get();

        return ['columns' => $columns, 'rows' => $rows->take(50), 'hasMore' => $rows->count() > 50];
    }
}
