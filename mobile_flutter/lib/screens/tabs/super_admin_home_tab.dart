import 'package:flutter/material.dart';

class SuperAdminHomeTab extends StatelessWidget {
  const SuperAdminHomeTab({super.key});

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Platform Control', style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 8),
                const Text('Organizations, admins, and platform limits will live here.'),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
