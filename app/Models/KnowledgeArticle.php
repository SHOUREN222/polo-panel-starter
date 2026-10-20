<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeArticle extends Model
{
    use \Stancl\Tenancy\Database\Concerns\CentralConnection; // 平台共享，分站从 central 读
    protected $fillable = ['title', 'content', 'category', 'show', 'sort'];

    protected function casts(): array
    {
        return ['show' => 'boolean'];
    }

    /**
     * Articles are shared platform-wide — agents get decent docs without
     * writing any — but a 分站 customer reading "欢迎使用 Polo 全球网络服务"
     * is being told whose platform they're really on. Write {{site_name}} in
     * the article and it resolves to whichever shop is being viewed.
     */
    public function forCurrentSite(string $text): string
    {
        return strtr($text, [
            '{{site_name}}' => (string) Setting::get('site_name', config('app.name')),
        ]);
    }

    public function renderedTitle(): string
    {
        return $this->forCurrentSite((string) $this->title);
    }

    public function renderedContent(): string
    {
        return $this->forCurrentSite((string) $this->content);
    }
}
