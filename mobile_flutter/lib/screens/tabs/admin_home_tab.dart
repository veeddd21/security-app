import 'package:flutter/material.dart';

class AdminHomeTab extends StatelessWidget {
  const AdminHomeTab({super.key});

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
                Text('Admin Overview', style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 8),
                const Text('Master, maps, guards, duty sites, and bookings will live here.'),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
