# Regenerate the PWA / TWA icon set.
#
# WHY: the shipped icons were dark-on-dark — icon-192/512 had a pure black
# background (0,0,0) behind a navy seal (0,0,79), and maskable-512 had the navy
# seal (0,0,66) on a navy background (31,69,112). Both were effectively
# invisible on a home screen. Verified by sampling pixels before this change.
#
# Sources are the real artwork, not the broken icons:
#   assets/img/MarikinaLogo.jpg         transparent navy seal -> client
#   assets/img/Basura Module Logo.jpg   logo on white         -> admin
#
# Each source is auto-cropped to its visible bounds before scaling, so the
# artwork is sized by its content rather than the source canvas padding.
#
# No ImageMagick or PHP GD on this box, so this uses .NET System.Drawing.

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$root = Split-Path $PSScriptRoot -Parent
$imgDir = Join-Path $root 'assets\icons'
$srcClient = Join-Path $root 'assets\img\MarikinaLogo.jpg'
$srcAdmin = Join-Path $root 'assets\img\Basura Module Logo.jpg'

# Light brand-tinted background: distinct from pure white on a light wallpaper
# and from any dark theme, while staying clearly "light".
$BG_LIGHT     = [System.Drawing.Color]::FromArgb(255, 244, 247, 250)
$BG_MASKABLE  = [System.Drawing.Color]::FromArgb(255, 238, 243, 248)

function Get-VisibleBounds {
    param([System.Drawing.Bitmap]$Bmp)
    # Coarse scan (step 4) keeps this fast in PowerShell; the sub-pixel error is
    # irrelevant because everything downstream resamples anyway.
    $step = 4
    $minX = $Bmp.Width; $maxX = -1; $minY = $Bmp.Height; $maxY = -1
    for ($y = 0; $y -lt $Bmp.Height; $y += $step) {
        for ($x = 0; $x -lt $Bmp.Width; $x += $step) {
            $c = $Bmp.GetPixel($x, $y)
            $visible = if ($c.A -gt 16) {
                # Opaque sources (like the Basura logo) need a whiteness test;
                # transparent ones just need alpha.
                -not ($c.R -ge 235 -and $c.G -ge 235 -and $c.B -ge 235)
            } else { $false }
            if ($visible) {
                if ($x -lt $minX) { $minX = $x }
                if ($x -gt $maxX) { $maxX = $x }
                if ($y -lt $minY) { $minY = $y }
                if ($y -gt $maxY) { $maxY = $y }
            }
        }
    }
    if ($maxX -lt 0) { return $null }
    [pscustomobject]@{
        X = $minX; Y = $minY
        W = ($maxX - $minX + 1); H = ($maxY - $minY + 1)
    }
}

function Write-IconFromBitmap {
    param(
        [System.Drawing.Bitmap]$Bitmap,
        [string]$OutFile,
        [int]$Size,
        [double]$ContentFraction,
        [System.Drawing.Color]$Background
    )

    $b = Get-VisibleBounds $Bitmap
    if (-not $b) { throw "no visible content for $OutFile" }

    $canvas = New-Object System.Drawing.Bitmap $Size, $Size, ([System.Drawing.Imaging.PixelFormat]::Format24bppRgb)
    $g = [System.Drawing.Graphics]::FromImage($canvas)
    $g.InterpolationMode  = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode    = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.SmoothingMode      = [System.Drawing.Drawing2D.SmoothingMode]::HighQuality
    $g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
    $g.Clear($Background)

    # Fit the artwork inside ContentFraction of the canvas, preserving ratio.
    $target = $Size * $ContentFraction
    $scale = [Math]::Min($target / $b.W, $target / $b.H)
    $dw = [int]($b.W * $scale); $dh = [int]($b.H * $scale)
    $dest = New-Object System.Drawing.Rectangle `
        ([int](($Size - $dw) / 2)), ([int](($Size - $dh) / 2)), $dw, $dh
    $src  = New-Object System.Drawing.Rectangle $b.X, $b.Y, $b.W, $b.H
    $g.DrawImage($Bitmap, $dest, $src, [System.Drawing.GraphicsUnit]::Pixel)
    $g.Dispose()

    $path = Join-Path $imgDir $OutFile
    $canvas.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
    $canvas.Dispose()
    Write-Output ("  {0,-24} {1}x{1}  {2,6:N0} KB" -f $OutFile, $Size, ((Get-Item $path).Length / 1KB))
}

function Load-Prepared {
    # Load a file, flatten onto white, and return a 32bpp ARGB bitmap so
    # bounds detection and scaling behave identically for every source.
    param([string]$Path, [int]$Size = 1024)
    $src = [System.Drawing.Bitmap]::FromFile($Path)
    $out = New-Object System.Drawing.Bitmap $Size, $Size, ([System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $g = [System.Drawing.Graphics]::FromImage($out)
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.DrawImage($src, 0, 0, $Size, $Size)
    $g.Dispose()
    $src.Dispose()
    return $out
}

function New-DeepenedArtwork {
    # The Basura logo ships as blue-grey artwork (darkest ~RGB 66,101,143) on a
    # baked-in white background. On a light icon background that measures only
    # 4.2:1 — readable at 512px but weak at launcher size. Deepening it toward
    # the client's navy reaches ~6.5:1 and ties the two apps together visually.
    #
    # White is the trap: scaling it would grey out the background. So near-white
    # is keyed to transparent and only real artwork is darkened. LockBits is
    # used because per-pixel GetPixel/SetPixel over 512x512 is minutes of work.
    param([string]$Path, [int]$Size = 512, [double]$Factor = 0.42, [int]$WhiteCutoff = 232)
    $bmp = Load-Prepared -Path $Path -Size $Size

    $rect = New-Object System.Drawing.Rectangle 0, 0, $Size, $Size
    $data = $bmp.LockBits($rect, [System.Drawing.Imaging.ImageLockMode]::ReadWrite, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $bytes = New-Object byte[] ($data.Stride * $Size)
    [System.Runtime.InteropServices.Marshal]::Copy($data.Scan0, $bytes, 0, $bytes.Length)
    for ($i = 0; $i -lt $bytes.Length; $i += 4) {
        $b = $bytes[$i]; $gr = $bytes[$i + 1]; $r = $bytes[$i + 2]
        if ($r -ge $WhiteCutoff -and $gr -ge $WhiteCutoff -and $b -ge $WhiteCutoff) {
            $bytes[$i + 3] = 0                     # background -> transparent
        } else {
            $bytes[$i]     = [int]($b     * $Factor)
            $bytes[$i + 1] = [int]($gr   * $Factor)
            $bytes[$i + 2] = [int]($r    * $Factor)
            $bytes[$i + 3] = 255
        }
    }
    [System.Runtime.InteropServices.Marshal]::Copy($bytes, 0, $data.Scan0, $bytes.Length)
    $bmp.UnlockBits($data)
    return $bmp
}

function New-HardenedArtwork {
    # At 32px the seal's thin lines anti-alias into semi-transparent mush and the
    # whole mark washes out to a pale smudge (measured 2.1:1 contrast). Threshold
    # the alpha so the crest stays a solid, crisp silhouette at tab size.
    param([string]$Path, [int]$Size = 256, [int]$AlphaCutoff = 70)
    $bmp = Load-Prepared -Path $Path -Size $Size
    $ink = [System.Drawing.Color]::FromArgb(255, 20, 32, 60)   # deep navy ink

    $rect = New-Object System.Drawing.Rectangle 0, 0, $Size, $Size
    $data = $bmp.LockBits($rect, [System.Drawing.Imaging.ImageLockMode]::ReadWrite, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $bytes = New-Object byte[] ($data.Stride * $Size)
    [System.Runtime.InteropServices.Marshal]::Copy($data.Scan0, $bytes, 0, $bytes.Length)
    for ($i = 0; $i -lt $bytes.Length; $i += 4) {
        if ($bytes[$i + 3] -ge $AlphaCutoff) {
            $bytes[$i] = $ink.B; $bytes[$i + 1] = $ink.G; $bytes[$i + 2] = $ink.R; $bytes[$i + 3] = 255
        } else {
            $bytes[$i + 3] = 0
        }
    }
    [System.Runtime.InteropServices.Marshal]::Copy($bytes, 0, $data.Scan0, $bytes.Length)
    $bmp.UnlockBits($data)
    # Ownership passes to the caller, which disposes after writing the icon.
    return $bmp
}

function Get-CenteredCrop {
    # The Marikina seal is fine line art: only ~20% of its canvas is opaque, so
    # downscaling the whole crest to a 32px tab icon leaves sub-pixel lines that
    # average into the background (measured 2.2:1). The central shield/torch
    # emblem is dense and solid, so cropping into it keeps the mark readable.
    param([System.Drawing.Bitmap]$Bmp, [double]$Fraction = 0.5)
    $w = [int]($Bmp.Width * $Fraction)
    $h = [int]($Bmp.Height * $Fraction)
    $x = [int](($Bmp.Width - $w) / 2)
    $y = [int](($Bmp.Height - $h) / 2)
    $out = $Bmp.Clone((New-Object System.Drawing.Rectangle $x, $y, $w, $h), $Bmp.PixelFormat)
    return $out
}

# --- Client -----------------------------------------------------------------
Write-Output "Client - Marikina seal on light background:"
$seal = Load-Prepared -Path $srcClient -Size 1024
Write-IconFromBitmap $seal 'icon-192.png'         192 0.78 $BG_LIGHT
Write-IconFromBitmap $seal 'icon-512.png'         512 0.78 $BG_LIGHT
Write-IconFromBitmap $seal 'apple-touch-icon.png' 180 0.92 $BG_LIGHT
# Maskable: Android crops to an adaptive shape and may lose the outer ~20%, so
# the artwork has to sit inside the centre safe zone -> smaller fraction.
Write-IconFromBitmap $seal 'maskable-512.png'     512 0.55 $BG_MASKABLE
$seal.Dispose()

# The 32px tab icon crops into the dense central emblem and uses the hardened
# silhouette, so it stays a solid mark instead of dissolving into the background.
$hardSeal = New-HardenedArtwork -Path $srcClient -Size 256
$favArt = Get-CenteredCrop $hardSeal 0.52
Write-IconFromBitmap $favArt 'favicon-32.png' 32 0.94 $BG_LIGHT
$favArt.Dispose(); $hardSeal.Dispose()

# --- Admin ------------------------------------------------------------------
Write-Output "Admin - Basura logo deepened to navy on light background:"
$adminArt = New-DeepenedArtwork -Path $srcAdmin -Size 512 -Factor 0.42
Write-IconFromBitmap $adminArt 'admin-icon-192.png'     192 0.78 $BG_LIGHT
Write-IconFromBitmap $adminArt 'admin-icon-512.png'     512 0.78 $BG_LIGHT
Write-IconFromBitmap $adminArt 'admin-maskable-512.png' 512 0.55 $BG_MASKABLE
$adminArt.Dispose()

Write-Output "Done."
