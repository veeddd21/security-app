import 'package:flutter/material.dart';

ThemeData buildDarkTheme() {
  const gold = Color(0xFFF3C24F);
  const jade = Color(0xFF47D8A2);
  const bg = Color(0xFF0A1320);
  const surface = Color(0xFF111827);

  final scheme = ColorScheme.fromSeed(
    seedColor: gold,
    brightness: Brightness.dark,
    surface: surface,
  ).copyWith(
    primary: gold,
    secondary: jade,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: bg,
    cardTheme: CardThemeData(
      color: surface,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: bg,
      foregroundColor: Colors.white,
      centerTitle: false,
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: surface,
      modalBackgroundColor: surface,
    ),
  );
}

ThemeData buildLightTheme() {
  const gold = Color(0xFFF3C24F);
  const jade = Color(0xFF47D8A2);
  const bg = Color(0xFFFFFFFF);
  const surface = Color(0xFFF8FAFC);

  final scheme = ColorScheme.fromSeed(
    seedColor: gold,
    brightness: Brightness.light,
    surface: surface,
  ).copyWith(
    primary: gold,
    secondary: jade,
    onPrimary: const Color(0xFF08101D),
    onSurface: const Color(0xFF111827),
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: bg,
    cardTheme: CardThemeData(
      color: surface,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
    ),
    appBarTheme: const AppBarTheme(
      backgroundColor: bg,
      foregroundColor: Color(0xFF111827),
      centerTitle: false,
      elevation: 0,
      surfaceTintColor: Colors.transparent,
    ),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: surface,
      modalBackgroundColor: surface,
    ),
  );
}

ThemeData buildAppTheme() => buildDarkTheme();
