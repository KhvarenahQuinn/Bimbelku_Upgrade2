<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminRatingController extends Controller
{
    // 1. Ambil Semua Rating (Terbaru dulu)
    public function index()
    {
        // Load relasi student (pemberi) dan teacher (penerima)
        $ratings = Rating::with(['student', 'teacher'])
            ->orderBy('created_at', 'desc')
            ->limit(300)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $ratings->map(function ($r) {
                return [
                    'id' => $r->id,
                    'rating' => $r->rating,
                    'review' => $r->review,
                    'created_at' => $r->created_at->format('d M Y, H:i'),
                    'student_name' => $r->student ? $r->student->name : 'User Terhapus',
                    'student_email' => $r->student ? $r->student->email : '-',
                    'teacher_name' => $r->teacher ? $r->teacher->name : 'User Terhapus',
                ];
            }),
        ]);
    }

    // 2. Hapus Rating (Moderasi)
    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($id) {
            $rating = Rating::query()->lockForUpdate()->find($id);
            if (! $rating) {
                abort(404, 'Data ulasan tidak ditemukan.');
            }

            $rating->delete();
        });

        return response()->json([
            'message' => 'Ulasan berhasil dihapus.',
        ]);
    }
}
