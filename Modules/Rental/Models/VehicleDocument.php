<?php

declare(strict_types=1);

namespace Modules\Rental\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One paper of one car, filed under the year it belongs to.
 *
 * New papers go on the PRIVATE disk and are only handed out through a
 * signed-in download, so a forwarded link is worth nothing. Rows copied from
 * the car's older single fields point at the public disk, where those files
 * already were.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $year
 * @property string $kind
 * @property string|null $title
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 */
final class VehicleDocument extends Model
{
    public const DISK = 'local';

    public const FOLDER = 'rental_vehicle_docs';

    protected $table = 'rental_vehicle_documents';

    /** @var list<string> */
    protected $fillable = ['vehicle_id', 'year', 'kind', 'title', 'original_name', 'disk', 'path', 'size', 'uploaded_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['vehicle_id' => 'integer', 'year' => 'integer', 'size' => 'integer'];
    }

    /**
     * The kinds of paper, as the screen names them.
     *
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return [
            'registration' => __('Registration card'),
            'insurance' => __('Insurance'),
            'invoice' => __('Purchase invoice'),
            'agreement' => __('Agreement'),
            'other' => __('Other paper'),
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function kindLabel(): string
    {
        return self::kinds()[$this->kind] ?? self::kinds()['other'];
    }

    /** What to call the file when it is downloaded: its own name, made safe. */
    public function downloadName(): string
    {
        $name = trim((string) preg_replace('/[^\pL\pN._ -]+/u', '_', $this->original_name));

        return $name !== '' ? $name : 'document-' . $this->id;
    }

    public function fileExists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Remove the row, and the file with it when the file is ours alone. A row
     * copied from the car's older single field shares its file with that
     * field, so the file stays while the field still points at it.
     */
    public function remove(): void
    {
        if ($this->disk === self::DISK) {
            Storage::disk($this->disk)->delete($this->path);
        }

        $this->delete();
    }
}
